<?php

declare(strict_types=1);

/**
 * Amor Factory System — redesigned UI preview shell/router.
 *
 * Deployed SIDE BY SIDE with the existing JSON API and every old UAT
 * wizard (api/_import-po/, api/_production-uat/, api/_fg-uat/,
 * api/_do-uat/) — none of those are touched, deleted, or superseded by
 * this router. This page only renders; every mutating action a page
 * offers goes through the SAME real JSON API those UAT wizards already
 * call (see assets/js/app.js), so no business logic is duplicated here.
 *
 * ?page=<key> selects the page (default: dashboard). Unknown keys fall
 * back to dashboard rather than 404ing, since this is a preview shell,
 * not a public site.
 */

require __DIR__ . '/../app/ui/bootstrap.php';
require __DIR__ . '/../app/ui/layout.php';

/** @var array<string,array{title:string,subtitle:string}> */
$pages = [
    'dashboard' => ['title' => 'Dashboard Operasional', 'subtitle' => 'Pantau proses operasional factory hari ini.'],
    'pesanan-toko' => ['title' => 'Pesanan Toko', 'subtitle' => 'Kelola PO awal, tambahan/revisi, dan status permintaan toko.'],
    'pesanan-khusus-toko' => ['title' => 'Pesanan Khusus Toko', 'subtitle' => 'Pesanan tambahan dari toko di luar PO reguler.'],
    'pesanan-khusus-toko-detail' => ['title' => 'Pesanan Khusus Toko', 'subtitle' => 'Detail satu pesanan khusus toko.'],
    'pesanan-non-toko' => ['title' => 'Pesanan Non-Toko', 'subtitle' => 'Pesanan dari konsumen langsung, CS, sales executive, atau umum.'],
    'pesanan-non-toko-detail' => ['title' => 'Pesanan Non-Toko', 'subtitle' => 'Detail satu pesanan non-toko.'],
    'produksi' => ['title' => 'Produksi', 'subtitle' => 'Kelola dan pantau realisasi produksi harian.'],
    'produksi-demand' => ['title' => 'Produksi', 'subtitle' => 'Order Masuk / Demand Tambahan dari Pesanan Khusus Toko dan Pesanan Non-Toko.'],
    'produksi-task-per-divisi' => ['title' => 'Produksi', 'subtitle' => 'Task per Divisi — target, realisasi, dan reject produksi lintas sumber demand.'],
    'fg-packing' => ['title' => 'FG & Packing', 'subtitle' => 'Verifikasi hasil produksi dan pantau proses packing.'],
    'fg-khusus-non-toko' => ['title' => 'FG Sumber Khusus / Non-Toko', 'subtitle' => 'Verifikasi FG untuk Pesanan Khusus Toko dan Pesanan Non-Toko — terpisah dari FG PO Reguler.'],
    'delivery-order' => ['title' => 'Delivery Order', 'subtitle' => 'Kelola DO toko, dokumen pengiriman, dan fulfillment.'],
    'delivery-order-detail' => ['title' => 'Delivery Order', 'subtitle' => 'Detail dokumen dan pengiriman bertahap.'],
    'delivery-order-khusus-non-toko' => ['title' => 'DO Pesanan Khusus / Non-Toko', 'subtitle' => 'DO terpisah per sumber untuk Pesanan Khusus Toko dan Pesanan Non-Toko.'],
    'delivery-order-khusus-non-toko-detail' => ['title' => 'DO Pesanan Khusus / Non-Toko', 'subtitle' => 'Detail satu DO khusus/non-toko.'],
    'pengiriman' => ['title' => 'Pengiriman', 'subtitle' => 'Kelola pengiriman aktual dan pengiriman bertahap.'],
    'konfirmasi-toko' => ['title' => 'Konfirmasi Toko', 'subtitle' => 'Tinjau konfirmasi penerimaan barang dari toko dan verifikasi selisih.'],
    'konfirmasi-toko-detail' => ['title' => 'Konfirmasi Toko', 'subtitle' => 'Detail konfirmasi penerimaan satu pengiriman.'],
    'replacement-reject' => ['title' => 'Replacement Reject', 'subtitle' => 'Tindak lanjut reject yang sudah diverifikasi: Reject Final atau Kirim Ulang / Ganti Produk.'],
    'replacement-do-detail' => ['title' => 'Replacement Reject', 'subtitle' => 'Detail DO Replacement — pengiriman make-good tanpa harga.'],
    'bakery-portal-tokens' => ['title' => 'Portal Bakery', 'subtitle' => 'Kelola link permanen Portal Bakery per toko (generate/regenerate/revoke).'],
    'invoice' => ['title' => 'Invoice', 'subtitle' => 'Generate dan kelola invoice dari data pengiriman dan mutasi yang sudah terkonfirmasi.'],
    'invoice-detail' => ['title' => 'Invoice', 'subtitle' => 'Detail satu invoice.'],
    'retur-review' => ['title' => 'Retur', 'subtitle' => 'Tinjau dan verifikasi pengajuan Retur dari Portal Bakery.'],
    'mutasi-review' => ['title' => 'Mutasi Produk', 'subtitle' => 'Tinjau Mutasi antar toko dan putuskan kasus selisih.'],
    'master-data' => ['title' => 'Master Data', 'subtitle' => 'Produk, toko, divisi, dan pabrik.'],
    'laporan' => ['title' => 'Laporan', 'subtitle' => 'Ringkasan lintas tahap, dari PO sampai pengiriman.'],
    'pengaturan' => ['title' => 'Pengaturan', 'subtitle' => 'Akun, preferensi tampilan, dan sesi.'],
];

$page = (string) ($_GET['page'] ?? 'dashboard');
if (!isset($pages[$page])) {
    $page = 'dashboard';
}

// FINAL PRE-DEPLOY PATCH — server-side page-level role gate. Runs BEFORE
// ui_page_head() emits any output, so http_response_code(403) actually
// takes effect and NO page-specific data is ever queried for a denied
// role (the page file itself is never even required). This is the FIRST
// such gate in this router (every other page today relies purely on the
// JSON API's own role checks + sidebar hiding, per this file's own
// original docblock) — added here, narrowly, only for the pages this
// patch's own task explicitly named; every other page's behavior is
// completely unchanged.
$pageRoles = [
    // Tindak Lanjut Reject + the full cross-store traceability list is
    // Admin-level only (task's own "Admin traceability may remain Admin/
    // PPIC-only") — a scoped Production/FG_PACKING user never sees
    // another store's/factory's reject data through this page at all.
    'replacement-reject' => ['ADMIN', 'PPIC'],
    // The DO detail/ship page's own role matrix is documented as
    // IDENTICAL to Replacement\ReplacementController's own DO_ROLES
    // (ADMIN/PPIC/PRODUCTION) — the same tier already authorized to
    // create/ship a Replacement DO via the real API; restricting this
    // page to Admin/PPIC-only would block a legitimately-authorized
    // PRODUCTION user from the UI action their own API call already
    // permits, which is a usability regression, not a security fix.
    'replacement-do-detail' => ['ADMIN', 'PPIC', 'PRODUCTION'],
    // Portal Bakery token management is more sensitive than most admin
    // actions (a leaked link is a standing, no-login credential) — ADMIN
    // only, never PPIC (same reasoning the page file's own docblock gives).
    'bakery-portal-tokens' => ['ADMIN'],
    'retur-review' => ['ADMIN', 'PPIC'],
    'mutasi-review' => ['ADMIN', 'PPIC'],
    // Invoice generation is ADMIN-only end to end (InvoiceController's own
    // Auth::requireRole('ADMIN') gate) — hiding the nav link and the page
    // itself from any other role avoids a dead-end UI that would just
    // 403 on every underlying API call anyway.
    'invoice' => ['ADMIN'],
    'invoice-detail' => ['ADMIN'],
];
if (isset($pageRoles[$page]) && array_intersect($pageRoles[$page], $ui['roles']) === []) {
    http_response_code(403);
    header('Content-Type: text/html; charset=utf-8');
    echo '<!doctype html><html lang="id"><head><meta charset="utf-8"><title>Akses Ditolak</title></head>'
        . '<body style="font-family:system-ui,sans-serif;max-width:640px;margin:4rem auto;padding:0 1.5rem;color:#1a1a1a;">'
        . '<h1 style="font-size:1.25rem;">403 — Akses Ditolak</h1>'
        . '<p>Anda tidak memiliki izin untuk mengakses halaman ini.</p>'
        . '</body></html>';
    exit;
}

$activeNav = str_starts_with($page, 'delivery-order') ? 'delivery-order'
    : (str_starts_with($page, 'konfirmasi-toko') ? 'konfirmasi-toko'
    : (str_starts_with($page, 'replacement-') ? 'replacement-reject'
    : ((str_starts_with($page, 'pesanan-khusus-toko') || str_starts_with($page, 'pesanan-non-toko')) ? 'pesanan-toko'
    : (str_starts_with($page, 'produksi-') ? 'produksi'
    : (str_starts_with($page, 'fg-') && $page !== 'fg-packing' ? 'fg-packing'
    : (str_starts_with($page, 'invoice') ? 'invoice' : $page))))));

// Shared date/factory selection every page can use as its default filter
// state, so the topbar's date/factory chips stay meaningful app-wide.
$pdo = $ui['pdo'];
$factories = $pdo->query('SELECT factory_id, code, name FROM factory ORDER BY factory_id')->fetchAll();
$uiTanggal = (string) ($_GET['tanggal'] ?? date('Y-m-d'));
$uiFactoryId = isset($_GET['factoryId']) && $_GET['factoryId'] !== '' ? (int) $_GET['factoryId'] : (int) ($factories[0]['factory_id'] ?? 0);
$uiFactoryName = '-';
foreach ($factories as $f) {
    if ((int) $f['factory_id'] === $uiFactoryId) {
        $uiFactoryName = $f['name'];
        break;
    }
}

ui_page_head($ui, $activeNav, $pages[$page]['title'], $pages[$page]['subtitle'], [
    'tanggal' => $uiTanggal,
    'factoryId' => $uiFactoryId,
    'factoryName' => $uiFactoryName,
]);

$pageFile = __DIR__ . '/../app/ui/pages/' . $page . '.php';
if (is_file($pageFile)) {
    require $pageFile;
} else {
    echo ui_empty_state('Halaman belum tersedia', 'Bagian ini sedang dalam pengembangan.');
}

ui_page_foot();
