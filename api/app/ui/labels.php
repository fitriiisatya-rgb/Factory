<?php

declare(strict_types=1);

/**
 * Central Indonesian label maps + status-color system for the redesigned
 * UI. Business logic never changes what is STORED (draft/submitted/
 * reopened/shipped/... stay exactly as Phase 1-5 wrote them) — this file
 * only maps those stored values, or the presentation-only
 * displayStatusCode/fgStatusCode/packingStatusCode/itemStatusCode fields
 * Phase 3-5's own services already compute, to a label + badge color for
 * display. No raw enum value is ever printed directly in a page.
 */

/** Document-level lifecycle status shared by production_run/fg_batch (draft/submitted/reopened). */
function ui_doc_status_label(string $status): string
{
    return [
        'not_started' => 'Belum Dimulai',
        'draft' => 'Draft',
        'submitted' => 'Sudah Disubmit',
        'reopened' => 'Dibuka Kembali',
        'verified_fg' => 'Terverifikasi FG',
    ][$status] ?? ucfirst($status);
}

/** delivery_order.status. */
function ui_do_status_label(string $status): string
{
    return [
        'draft' => 'Draft',
        'preprinted' => 'Sudah Preprint',
        'ready' => 'Siap Diproses',
        'shipped' => 'Terkirim Penuh',
        'cancelled' => 'Dibatalkan',
    ][$status] ?? ucfirst($status);
}

/**
 * DO status as Admin should read it. A partial shipment deliberately
 * leaves delivery_order.status at draft/preprinted (ShipmentService only
 * flips it to 'shipped' once fully fulfilled), so "Draft" alone would hide
 * that goods already left — shown as "Sebagian Dikirim" instead. Display
 * only; the stored status is never changed here.
 * @param array{status:string,totalShipped?:float|int|string} $do
 */
function ui_do_status_display(array $do): string
{
    $status = (string) $do['status'];
    if (in_array($status, ['draft', 'preprinted'], true) && (float) ($do['totalShipped'] ?? 0) > 0.0001) {
        return 'Sebagian Dikirim';
    }
    return ui_do_status_label($status);
}

/** audit_log.action -> what Admin reads in "Aktivitas Terbaru" (never the raw code). */
function ui_activity_label(string $action): string
{
    return [
        'po.import' => 'PO diimpor/direvisi',
        'po.product.create_from_import' => 'Produk baru dari import PO',
        'product.create' => 'Produk ditambahkan',
        'product.update' => 'Produk diperbarui',
        'product.alias.create' => 'Alias produk ditambahkan',
        'store.create' => 'Toko ditambahkan',
        'store.update' => 'Toko diperbarui',
        'store.alias.create' => 'Alias toko ditambahkan',
        'production.draft.create' => 'Draft produksi dibuat',
        'production.draft.edit' => 'Hasil produksi disimpan',
        'production.submit' => 'Produksi disubmit',
        'production.reopen' => 'Produksi dibuka kembali',
        'production.overproduction' => 'Produksi melebihi target',
        'production.target_changed_from_po_revision' => 'Target produksi berubah (revisi PO)',
        'fg.draft.create' => 'Draft FG dibuat',
        'fg.draft.edit' => 'Verifikasi FG disimpan',
        'fg.packing.submit_store' => 'Packing per toko disubmit',
        'fg.submit' => 'FG disubmit (stok siap kirim)',
        'fg.reopen' => 'FG dibuka kembali',
        'fg.source_inconsistency' => 'Sumber produksi FG berubah',
        'fg.source_refresh' => 'Sumber produksi FG disegarkan',
        'do.preprint' => 'DO dicetak',
        'do.refresh_from_po' => 'DO disegarkan dari PO',
        'do.cancel' => 'DO dibatalkan',
        'do.ship' => 'Pengiriman dibuat',
        'dispatch.claimed' => 'Driver mengambil tugas kirim',
        'dispatch.released' => 'Driver melepas tugas kirim',
        'dispatch.route_reordered' => 'Driver mengatur rute',
        'dispatch.departed' => 'Driver berangkat',
        'dispatch.claim_resolved' => 'Tugas kirim diselesaikan',
        'receipt.confirmed' => 'Toko konfirmasi penerimaan',
        'receipt.verified' => 'Selisih penerimaan diverifikasi',
        'shipment.email.sent' => 'Email pengiriman terkirim',
        'shipment.email.failed' => 'Email pengiriman gagal',
        'shipment.email.skipped' => 'Email pengiriman dilewati',
        'reject.finalized' => 'Reject diputuskan final',
        'replacement.created' => 'Kirim ulang reject dibuat',
        'replacement.fg_allocated' => 'Kirim ulang: stok FG dialokasikan',
        'replacement.production_needed' => 'Kirim ulang: perlu produksi',
        'replacement.production_actual_updated' => 'Kirim ulang: hasil produksi diisi',
        'replacement.fg_verified' => 'Kirim ulang: FG diverifikasi',
        'replacement.do_created' => 'DO kirim ulang dibuat',
        'replacement.shipped' => 'Kirim ulang dikirim',
        'replacement.received' => 'Kirim ulang diterima toko',
        'replacement.completed' => 'Kirim ulang selesai',
        'retur.created' => 'Toko mengajukan retur',
        'retur.verified' => 'Retur diverifikasi',
        'retur.rejected' => 'Retur ditolak',
        'mutasi.created' => 'Toko mengajukan mutasi',
        'mutasi.confirmed' => 'Toko tujuan konfirmasi mutasi',
        'mutasi.admin_reviewed' => 'Mutasi diputuskan Admin',
        'invoice.generated' => 'Invoice dibuat',
        'invoice.voided' => 'Invoice dibatalkan',
        'special_order.created' => 'Pesanan khusus dibuat',
        'special_order.confirmed' => 'Pesanan khusus dikonfirmasi',
        'special_order.sent_to_production' => 'Pesanan khusus dikirim ke produksi',
        'special_order.status_changed' => 'Status pesanan khusus berubah',
        'special_order.actual_updated' => 'Hasil produksi pesanan khusus diisi',
        'special_order.fg_verified' => 'FG pesanan khusus diverifikasi',
        'special_order.cancelled' => 'Pesanan khusus dibatalkan',
        'special_order_do.create' => 'DO pesanan khusus dibuat',
        'special_order_do.claim' => 'Driver mengambil DO pesanan khusus',
        'special_order_do.release' => 'Driver melepas DO pesanan khusus',
        'special_order_do.depart' => 'Pesanan khusus diberangkatkan',
        'special_order_do.courier_handover' => 'Pesanan khusus diserahkan ke kurir',
        'special_order_do.delivery_method_changed' => 'Metode kirim pesanan khusus diubah',
        'special_order_do.cancel' => 'DO pesanan khusus dibatalkan',
        'store_portal.token_issued' => 'Link Portal Bakery dibuat',
        'store_portal.token_revoked' => 'Link Portal Bakery dicabut',
        'user.created' => 'User dibuat',
        'user.updated' => 'User diperbarui',
        'user.activated' => 'User diaktifkan',
        'user.deactivated' => 'User dinonaktifkan',
        'user.password_reset' => 'Password user direset',
        'user.role_changed' => 'Peran user diubah',
        'user.division_access_changed' => 'Akses divisi user diubah',
        'user.factory_access_changed' => 'Akses pabrik user diubah',
    ][$action] ?? 'Aktivitas sistem';
}

/** shipment.status. */
function ui_shipment_status_label(string $status): string
{
    return [
        'active' => 'Aktif',
        'void' => 'Dibatalkan',
    ][$status] ?? ucfirst($status);
}

/** shipment_receipt.status (or the synthetic 'belum_dikonfirmasi' when no receipt row exists yet). */
function ui_receipt_status_label(string $status): string
{
    return [
        'belum_dikonfirmasi' => 'Belum Dikonfirmasi',
        'confirmed_ok' => 'Diterima Sesuai',
        'confirmed_discrepancy' => 'Ada Selisih',
        'verified' => 'Diverifikasi Admin',
    ][$status] ?? ucfirst($status);
}

/** shipment_email_delivery.status (Part I/K/L) — a shipment with no outbox row yet (should not normally happen — see Mail\ShipmentEmailService) also reads as "Belum Dikirim". */
function ui_email_status_label(?string $status): string
{
    return [
        'pending' => 'Belum Dikirim',
        'sent' => 'Terkirim',
        'failed' => 'Gagal',
        'no_email' => 'Email Toko Belum Diisi',
    ][$status ?? ''] ?? 'Belum Dikirim';
}

/** special_order.status (migration 0010 — Pesanan Khusus Toko / Pesanan Non-Toko). */
function ui_special_order_status_label(string $status): string
{
    return [
        'draft' => 'Draft',
        'confirmed' => 'Dikonfirmasi',
        'sent_to_production' => 'Dikirim ke Produksi',
        'in_production' => 'Sedang Diproduksi',
        'ready' => 'Siap',
        'completed' => 'Selesai',
        'cancelled' => 'Dibatalkan',
    ][$status] ?? ucfirst($status);
}

/**
 * Maps an already-Indonesian label (from any *StatusLabel field the
 * services compute, or from the maps above) to one of the 5 badge colors
 * in the task's own status design system. Unknown labels fall back to
 * neutral rather than erroring, so a future label never breaks the page.
 */
function ui_status_color(string $label): string
{
    static $neutral = ['Belum Diproduksi', 'Belum Diverifikasi', 'Belum Dikirim', 'Belum Dipacking', 'Belum Dimulai', 'Belum Dikonfirmasi', 'Perlu Produksi'];
    static $primary = ['Draft', 'Siap Diproses', 'Belum Diverifikasi FG', 'Sudah Dialokasikan'];
    static $warning = [
        'Dibuka Kembali', 'Sebagian Terverifikasi', 'Belum Sesuai Target', 'Sebagian Dikirim',
        'Sudah Preprint', 'Selisih', 'Sebagian Dipacking', 'Ada Selisih', 'Menunggu Konfirmasi',
        'Email Toko Belum Diisi', 'Dikirim ke Produksi', 'Sedang Diproduksi', 'Belum Selesai',
        'Sebagian FG / Perlu Produksi',
    ];
    static $success = [
        'Sudah Disubmit', 'Sesuai Target', 'Sesuai Produksi', 'Selesai Dipacking', 'Terkirim Penuh',
        'Terverifikasi FG', 'Aktif', 'Terkirim', 'Diterima Sesuai', 'Diverifikasi Admin', 'Siap', 'Selesai',
        'Bisa Dipenuhi dari FG', 'Tidak Perlu Produksi', 'Siap ke DO',
    ];
    static $danger = ['Overproduction', 'Dibatalkan', 'Error', 'Melebihi Produksi', 'Packing Melebihi FG', 'Nonaktif', 'Gagal'];

    if (in_array($label, $danger, true)) {
        return 'danger';
    }
    if (in_array($label, $success, true)) {
        return 'success';
    }
    if (in_array($label, $warning, true)) {
        return 'warning';
    }
    if (in_array($label, $primary, true)) {
        return 'primary';
    }
    if (in_array($label, $neutral, true)) {
        return 'neutral';
    }
    return 'neutral';
}

/** Renders a <span class="badge badge-*"> for any label — the ONLY place a status badge's HTML is built. */
function ui_badge(string $label): string
{
    $color = ui_status_color($label);
    return '<span class="badge badge-' . $color . '">' . ui_esc($label) . '</span>';
}

/**
 * Task per Divisi's demand-SOURCE badge (distinct axis from a status
 * badge — a task's source never changes, unlike its status) — matches
 * the approved mockup's legend colors exactly: PO Reguler=blue,
 * Pesanan Khusus=gold, Pesanan Non-Toko=green, Replacement Reject=red.
 */
function ui_task_source_badge(string $source, string $label): string
{
    $color = match ($source) {
        'po_reguler' => 'primary',
        'pesanan_khusus' => 'warning',
        'pesanan_non_toko' => 'success',
        'replacement_reject' => 'danger',
        default => 'neutral',
    };
    return '<span class="badge badge-' . $color . '">' . ui_esc($label) . '</span>';
}

/**
 * Downstream normalized source badge (Amor\Api\SpecialOrder\
 * NormalizedSourceType) — a DIFFERENT axis/vocabulary from
 * ui_task_source_badge() above (that one is Task per Divisi's PRODUCTION
 * demand source; this one is the shipment/DO/receipt's DOWNSTREAM
 * fulfillment source — task's own Final Pre-Live Rework Section A/H).
 */
function ui_normalized_source_badge(string $normalizedType, string $label): string
{
    $color = match ($normalizedType) {
        'REGULAR_STORE_PO' => 'primary',
        'SPECIAL_STORE_ORDER' => 'warning',
        'CS_ORDER', 'SALES_ORDER' => 'success',
        'DIRECT_CUSTOMER', 'GENERAL_ORDER' => 'neutral',
        'REPLACEMENT_REJECT' => 'danger',
        default => 'neutral',
    };
    return '<span class="badge badge-' . $color . '">' . ui_esc($label) . '</span>';
}
