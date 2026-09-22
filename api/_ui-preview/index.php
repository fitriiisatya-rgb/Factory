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
    'fg-packing' => ['title' => 'FG & Packing', 'subtitle' => 'Verifikasi hasil produksi dan pantau proses packing.'],
    'delivery-order' => ['title' => 'Delivery Order', 'subtitle' => 'Kelola DO toko, dokumen pengiriman, dan fulfillment.'],
    'delivery-order-detail' => ['title' => 'Delivery Order', 'subtitle' => 'Detail dokumen dan pengiriman bertahap.'],
    'pengiriman' => ['title' => 'Pengiriman', 'subtitle' => 'Kelola pengiriman aktual dan pengiriman bertahap.'],
    'konfirmasi-toko' => ['title' => 'Konfirmasi Toko', 'subtitle' => 'Tinjau konfirmasi penerimaan barang dari toko dan verifikasi selisih.'],
    'konfirmasi-toko-detail' => ['title' => 'Konfirmasi Toko', 'subtitle' => 'Detail konfirmasi penerimaan satu pengiriman.'],
    'master-data' => ['title' => 'Master Data', 'subtitle' => 'Produk, toko, divisi, dan pabrik.'],
    'laporan' => ['title' => 'Laporan', 'subtitle' => 'Ringkasan lintas tahap, dari PO sampai pengiriman.'],
    'pengaturan' => ['title' => 'Pengaturan', 'subtitle' => 'Akun, preferensi tampilan, dan sesi.'],
];

$page = (string) ($_GET['page'] ?? 'dashboard');
if (!isset($pages[$page])) {
    $page = 'dashboard';
}
$activeNav = str_starts_with($page, 'delivery-order') ? 'delivery-order'
    : (str_starts_with($page, 'konfirmasi-toko') ? 'konfirmasi-toko'
    : ((str_starts_with($page, 'pesanan-khusus-toko') || str_starts_with($page, 'pesanan-non-toko')) ? 'pesanan-toko'
    : ($page === 'produksi-demand' ? 'produksi' : $page)));

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
